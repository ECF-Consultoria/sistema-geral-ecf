import { useState, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Search, Megaphone, MegaphoneOff, AlertTriangle, ChevronDown, ArrowUpDown } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';

const STATUS_META = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' },
    'Não':          { cor: '#ef4444', label: 'Não faturou' },
    'Problema':     { cor: '#a855f7', label: 'Problema' },
};

/**
 * Retorna a cor do gasto de ADS por limiar universal:
 * - vermelho (>= alerta2): estouro crítico
 * - amarelo (>= alerta1): atenção
 * - verde (< alerta1): dentro do esperado
 */
function corAds(gasto, limites) {
    const alerta1 = (limites?.alerta1) ?? 1000;
    const alerta2 = (limites?.alerta2) ?? 2000;
    if (gasto >= alerta2) return '#ef4444'; // vermelho
    if (gasto >= alerta1) return '#ffe600'; // amarelo / ecf-yellow
    return '#22c55e'; // verde
}

const FILTROS = [
    { key: 'todas',        label: 'Todas' },
    { key: 'Sim',          label: 'No alvo' },
    { key: 'Em progresso', label: 'Em progresso' },
    { key: 'Não',          label: 'Não faturou' },
    { key: 'Problema',     label: 'Problema' },
    { key: 'ads',          label: 'Ads desligado' },
];

function StatusBadge({ status }) {
    const meta = STATUS_META[status] ?? { cor: '#94a3b8', label: status };
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold"
              style={{ background: `${meta.cor}22`, color: meta.cor }}>
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: meta.cor }} />
            {meta.label}
        </span>
    );
}

/**
 * Polos/Empresas — visão completa (tabela) de TODAS as empresas dos polos.
 * Aberta em aba própria pelo botão "Todas as empresas" do /polos.
 */
export default function PolosEmpresas({
    empresas       = [],
    meses          = [],
    mesSelecionado = null,
    mesRefLabel    = null,
    parcial        = false,
    totais         = { faturamento: 0, meta: 0, pct: 0, ativos: 0 },
    adsLimites     = { teto: 3000, alerta1: 1000, alerta2: 2000 },
    erro           = null,
}) {
    const [filtro, setFiltro]   = useState('todas');
    const [busca, setBusca]     = useState('');
    const [ordem, setOrdem]     = useState({ campo: 'faturamento', dir: 'desc' });
    const [aberta, setAberta]   = useState(null);   // cust_id expandido
    const [semanal, setSemanal] = useState({});

    const trocarMes = (e) => router.get(route('polos.empresas'), { mes: e.target.value }, { preserveScroll: true });

    const abrirSemanal = (cust) => {
        setAberta((cur) => (cur === cust ? null : cust));
        if (!semanal[cust]) {
            setSemanal((s) => ({ ...s, [cust]: { loading: true } }));
            fetch(route('polos.empresa.semanal', { cust, mes: mesSelecionado }))
                .then((r) => r.json())
                .then((d) => setSemanal((s) => ({ ...s, [cust]: { ...d, loading: false } })))
                .catch(() => setSemanal((s) => ({ ...s, [cust]: { erro: true, loading: false } })));
        }
    };

    const ordenarPor = (campo) => setOrdem((o) => ({
        campo,
        dir: o.campo === campo && o.dir === 'desc' ? 'asc' : 'desc',
    }));

    const lista = useMemo(() => {
        let l = empresas.filter((e) => {
            if (filtro === 'ads') { if (e.ads_desligado !== true) return false; }
            else if (filtro !== 'todas' && e.status !== filtro) return false;
            if (busca.trim() && !(`${e.nome} ${e.polo}`.toLowerCase().includes(busca.trim().toLowerCase()))) return false;
            return true;
        });
        const { campo, dir } = ordem;
        l = [...l].sort((a, b) => {
            let va = a[campo], vb = b[campo];
            if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb ?? '').toLowerCase(); }
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        return l;
    }, [empresas, filtro, busca, ordem]);

    const contar = (key) => key === 'todas' ? empresas.length
        : key === 'ads' ? empresas.filter((e) => e.ads_desligado === true).length
        : empresas.filter((e) => e.status === key).length;

    const Th = ({ campo, children, className }) => (
        <th className={cn('px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-white/40', className)}>
            <button type="button" onClick={() => ordenarPor(campo)} className="inline-flex items-center gap-1 hover:text-white/70">
                {children}
                <ArrowUpDown size={11} className={cn(ordem.campo === campo ? 'text-ecf-yellow' : 'opacity-40')} />
            </button>
        </th>
    );

    return (
        <AppLayout title="Empresas dos Polos">
            <Head title="Empresas dos Polos" />

            <div className="space-y-5 max-w-[1500px] mx-auto">

                {/* Cabeçalho */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <Link href={route('polos.index')} className="inline-flex items-center gap-1.5 text-white/40 hover:text-white/70 text-xs w-fit">
                            <ArrowLeft size={13} /> Voltar para Faturamento Polos
                        </Link>
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">Empresas dos Polos</h1>
                        <div className="flex items-center gap-2">
                            <p className="text-white/40 text-sm">{mesRefLabel ? `Mês: ${mesRefLabel}` : 'Visão completa'}</p>
                            {parcial && <span className="text-[10px] font-semibold uppercase text-ecf-yellow">· mês parcial</span>}
                        </div>
                    </div>

                    {meses.length > 0 && (
                        <div className="flex items-center gap-2">
                            <label className="text-white/40 text-xs uppercase tracking-wider">Mês</label>
                            <select value={mesSelecionado ?? ''} onChange={trocarMes}
                                    className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40">
                                {meses.map((m) => (
                                    <option key={m.value} value={m.value} className="bg-ecf-card text-white">
                                        {m.label}{m.parcial ? ' (parcial)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                {erro && (
                    <div className="flex items-start gap-3 rounded-xl border border-red-500/20 bg-red-500/[0.06] p-4">
                        <AlertTriangle size={18} className="text-red-400 mt-0.5 shrink-0" />
                        <p className="text-red-300 text-sm">{erro}</p>
                    </div>
                )}

                {/* KPIs */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <Kpi label="Empresas ativas" value={totais.ativos} />
                    <Kpi label="Faturamento (Adman)" value={formatCurrency(totais.faturamento)} accent />
                    <Kpi label="Meta total" value={formatCurrency(totais.meta)} />
                    <Kpi label="% da meta" value={`${totais.pct}%`} />
                </div>

                {/* Filtros + busca */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-1.5">
                        {FILTROS.map((f) => (
                            <button key={f.key} type="button" onClick={() => setFiltro(f.key)}
                                    className={cn('rounded-full px-3 py-1 text-xs font-semibold transition',
                                        filtro === f.key ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]')}>
                                {f.label} <span className="tabular-nums opacity-70">{contar(f.key)}</span>
                            </button>
                        ))}
                    </div>
                    <div className="relative">
                        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar empresa ou polo…"
                               className="w-64 rounded-lg border border-white/[0.1] bg-ecf-card pl-9 pr-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40 placeholder:text-white/25" />
                    </div>
                </div>

                {/* Tabela */}
                <div className="overflow-x-auto rounded-2xl border border-white/[0.08] bg-white/[0.02]">
                    <table className="w-full text-sm">
                        <thead className="border-b border-white/[0.08]">
                            <tr>
                                <Th campo="nome">Empresa</Th>
                                <Th campo="polo">Polo</Th>
                                <Th campo="fase">Fase</Th>
                                <Th campo="status">Status</Th>
                                <Th campo="faturamento" className="text-right">Faturamento</Th>
                                <Th campo="pct">% da meta</Th>
                                <Th campo="ads">ADS</Th>
                                <th className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-white/40">Sinais</th>
                                <th className="w-8" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.04]">
                            {lista.length === 0 && (
                                <tr><td colSpan={9} className="px-4 py-8 text-center text-white/40">Nenhuma empresa neste filtro.</td></tr>
                            )}
                            {lista.map((e) => {
                                const expandida  = aberta === e.cust_id;
                                const sem        = semanal[e.cust_id];
                                const statusCor  = (STATUS_META[e.status] ?? {}).cor ?? '#94a3b8';
                                const gastoAds   = e.ads ?? 0;
                                const adsCorHex  = corAds(gastoAds, adsLimites);
                                const adsPct     = Math.min(gastoAds / (adsLimites.teto || 3000) * 100, 100);
                                return (
                                    <>
                                        <tr key={e.cust_id} onClick={() => abrirSemanal(e.cust_id)}
                                            className={cn('cursor-pointer hover:bg-white/[0.03] transition', expandida && 'bg-white/[0.04]')}>
                                            <td className="px-4 py-3 text-white/90 font-medium">{e.nome}</td>
                                            <td className="px-4 py-3 text-white/60">{e.polo}</td>
                                            <td className="px-4 py-3 text-white/40 text-xs">{e.fase}</td>
                                            <td className="px-4 py-3"><StatusBadge status={e.status} /></td>
                                            <td className="px-4 py-3 text-right text-white font-semibold tabular-nums">{formatCurrency(e.faturamento)}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                                        <div className="h-full rounded-full" style={{ width: `${Math.min(e.pct ?? 0, 100)}%`, background: statusCor }} />
                                                    </div>
                                                    <span className="text-white/40 text-xs tabular-nums w-14">{e.pct}%</span>
                                                </div>
                                            </td>
                                            {/* Coluna ADS: barra de progresso do gasto mensal vs teto, colorida por limiar */}
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-24 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                                        <div className="h-full rounded-full" style={{ width: `${adsPct}%`, background: adsCorHex }} />
                                                    </div>
                                                    <span className="text-white/40 text-xs tabular-nums whitespace-nowrap">
                                                        {formatCurrency(gastoAds)} <span className="text-white/20">/ {formatCurrency(adsLimites.teto)}</span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1.5">
                                                    {e.ads_desligado === true && <span title="Ads desligado" className="text-red-400"><MegaphoneOff size={15} /></span>}
                                                    {e.ads_desligado === false && <span title="Ads ligado" className="text-green-400"><Megaphone size={15} /></span>}
                                                    {e.problema && <span title={e.problema_nota || 'Problema'} className="text-purple-400"><AlertTriangle size={15} /></span>}
                                                </div>
                                            </td>
                                            <td className="px-2 text-white/30"><ChevronDown size={16} className={cn('transition', expandida && 'rotate-180')} /></td>
                                        </tr>
                                        {expandida && (
                                            <tr key={`${e.cust_id}-sem`} className="bg-white/[0.02]">
                                                <td colSpan={9} className="px-6 py-4">
                                                    <p className="text-white/30 text-[10px] uppercase tracking-wider mb-2">Faturamento e ADS por semana — {e.nome}</p>
                                                    {sem?.loading && <p className="text-white/40 text-xs">Carregando da Adman…</p>}
                                                    {sem?.erro && <p className="text-red-300 text-xs">Falha ao buscar o semanal.</p>}
                                                    {sem && !sem.loading && !sem.erro && (
                                                        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 max-w-3xl">
                                                            {sem.semanas?.map((s) => {
                                                                const max         = Math.max(...sem.semanas.map((x) => x.faturamento), 1);
                                                                const gastoSemAds = s.ads ?? 0;
                                                                const adsSemPct   = Math.min(gastoSemAds / (adsLimites.teto || 3000) * 100, 100);
                                                                const adsSemCor   = corAds(gastoSemAds, adsLimites);
                                                                return (
                                                                    <div key={s.semana} className="rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
                                                                        <p className="text-white/40 text-[11px]">Semana {s.semana}</p>
                                                                        <p className="text-white font-semibold tabular-nums mt-0.5">{formatCurrency(s.faturamento)}</p>
                                                                        <div className="mt-2 h-1.5 rounded bg-white/[0.06] overflow-hidden">
                                                                            <div className="h-full rounded bg-ecf-yellow" style={{ width: `${(s.faturamento / max) * 100}%` }} />
                                                                        </div>
                                                                        {/* ADS da semana: rótulo + valor + barra fina colorida por limiar */}
                                                                        <div className="mt-2.5 flex items-center justify-between">
                                                                            <p className="text-white/40 text-[10px] uppercase tracking-wide">ADS</p>
                                                                            <p className="text-white/70 text-[11px] tabular-nums">{formatCurrency(gastoSemAds)}</p>
                                                                        </div>
                                                                        <div className="mt-1 h-1 rounded bg-white/[0.06] overflow-hidden">
                                                                            <div className="h-full rounded" style={{ width: `${adsSemPct}%`, background: adsSemCor }} />
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <p className="text-white/30 text-xs text-center">{lista.length} de {empresas.length} empresas · clique numa linha para ver o faturamento semanal</p>
            </div>
        </AppLayout>
    );
}

function Kpi({ label, value, accent = false }) {
    return (
        <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
            <p className="text-white/30 text-[10px] uppercase tracking-wider">{label}</p>
            <p className={cn('font-display font-extrabold text-lg mt-0.5', accent ? 'text-ecf-yellow' : 'text-white/85')}>{value}</p>
        </div>
    );
}
