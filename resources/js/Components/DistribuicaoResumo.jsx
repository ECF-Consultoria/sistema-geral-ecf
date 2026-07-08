import { useMemo, useState, useEffect } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { PieChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import * as Popover from '@radix-ui/react-popover';
import {
    Search, Download, ChevronDown, ArrowRight, X, List, Table2, Copy, Check,
    PieChart as PieIcon, Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// Registro tree-shakeable (mesmo conjunto do StatusDonut).
echarts.use([PieChart, TooltipComponent, CanvasRenderer]);

/**
 * DistribuicaoResumo — PADRÃO REUTILIZÁVEL de gráfico de distribuição do Painel Polos.
 *
 * Filosofia "Overview first, details on demand": o card é um RESUMO EXECUTIVO compacto
 * (cabe muitos por tela, alta densidade / escaneabilidade) e o detalhamento completo só
 * aparece sob demanda, num DRAWER lateral.
 *
 *  CARD (compacto): ícone + título + subtítulo · donut pequeno (total no centro, Top-N
 *                   fatias + "Outros") · legenda Top-N (5) · rodapé "N categorias · Ver todas".
 *  DRAWER (Ver todas): TODAS as categorias + busca + ordenação + abas Lista/Tabela +
 *                      data-bars + exportação (CSV/copiar). 100% dos dados, sem poluir o card.
 *
 * Integração: `onClick(key)` mantém o cross-filter com a grade; `activeKey` destaca a
 * categoria (as demais em cinza). Cores por paleta categórica (rank), estáveis em card+drawer.
 *
 * dados: [{ key, label, count, cor? }].
 */

const PALETA = [
    '#8b7cf6', '#3b9ae1', '#22c9a6', '#f0567a', '#a855f7', '#fbbf24',
    '#fb923c', '#38bdf8', '#34d399', '#f472b6', '#60a5fa', '#a3e635',
    '#e879f9', '#facc15', '#2dd4bf', '#c084fc',
];
const COR_OUTROS = '#6b7280';

const CARD = 'relative overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.02] before:absolute before:inset-x-0 before:top-0 before:h-px before:bg-gradient-to-r before:from-transparent before:via-white/[0.10] before:to-transparent';

const pctNum = (n, total) => (total > 0 ? (n / total) * 100 : 0);
const fmtPct = (p) => `${p.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const fmtInt = (n) => Number(n ?? 0).toLocaleString('pt-BR');

export default function DistribuicaoResumo({
    titulo,
    subtitulo,
    icone: Icone = PieIcon,
    dados = [],
    onClick,
    activeKey = null,
    centroLabel = 'empresas',
    topN = 5,           // categorias inline (legenda) + fatias reais no donut antes de "Outros"
    vazioLabel = 'Sem dados',
}) {
    const [aberto, setAberto] = useState(false);
    const sub = subtitulo ?? `Distribuição de ${centroLabel} por ${titulo}`;

    // Ranking desc + cor estável por posição (usada em card e drawer).
    const ordenados = useMemo(() => {
        const arr = dados
            .filter((d) => (d.count || 0) > 0)
            .sort((a, b) => (b.count || 0) - (a.count || 0) ||
                String(a.label ?? '').localeCompare(String(b.label ?? ''), 'pt-BR', { sensitivity: 'base' }));
        return arr.map((d, i) => ({ ...d, cor: d.cor || PALETA[i % PALETA.length] }));
    }, [dados]);

    const total = useMemo(() => ordenados.reduce((a, d) => a + (d.count || 0), 0), [ordenados]);
    const nCat = ordenados.length;
    const temMais = nCat > topN;

    // Donut: Top-N fatias + "Outros" (só agrupa se sobra ≥ 2).
    const fatias = useMemo(() => {
        const topo = ordenados.slice(0, topN);
        const resto = ordenados.slice(topN);
        if (resto.length >= 2) {
            return [...topo, { key: '__outros__', label: `Outros (${resto.length})`, count: resto.reduce((a, d) => a + d.count, 0), cor: COR_OUTROS, outros: true }];
        }
        return ordenados;
    }, [ordenados, topN]);

    const legenda = ordenados.slice(0, topN);

    const option = useMemo(() => ({
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            backgroundColor: 'rgba(15,17,22,0.95)',
            borderColor: 'rgba(255,255,255,0.10)', borderWidth: 1,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (p) => `${p.marker} ${p.name}<br/><b>${fmtInt(p.value)}</b> · ${p.percent}%`,
        },
        series: [{
            type: 'pie',
            radius: ['60%', '86%'],
            center: ['50%', '50%'],
            avoidLabelOverlap: false,
            label: { show: false }, labelLine: { show: false },
            itemStyle: { borderRadius: 4, borderColor: '#0f1116', borderWidth: 2 },
            emphasis: { scale: true, scaleSize: 5 },
            data: fatias.map((d) => ({
                value: d.count, name: d.label, chave: d.key,
                itemStyle: { color: d.cor, opacity: activeKey && activeKey !== d.key ? 0.25 : 1 },
            })),
        }],
    }), [fatias, activeKey]);

    const onEvents = { click: (p) => { const k = p?.data?.chave; if (k && k !== '__outros__') onClick?.(k); } };

    return (
        <div className={cn(CARD, 'flex h-full flex-col p-4')}>
            {/* Cabeçalho */}
            <div className="flex items-start gap-2.5">
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-ecf-yellow/10 text-ecf-yellow">
                    <Icone size={17} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="truncate font-display text-[14px] font-bold leading-tight text-white">{titulo}</h3>
                    <p className="truncate text-[11px] text-white/40">{sub}</p>
                </div>
            </div>

            {total === 0 ? (
                <div className="flex flex-1 items-center justify-center py-8 text-[13px] text-white/30">{vazioLabel}</div>
            ) : (
                <>
                    {/* Corpo: donut + legenda Top-N */}
                    <div className="mt-3 flex items-center gap-3">
                        <div className="relative shrink-0" style={{ width: 104, height: 104 }}>
                            <ReactEChartsCore echarts={echarts} option={option} notMerge lazyUpdate onEvents={onEvents}
                                style={{ height: 104, width: 104 }} opts={{ renderer: 'canvas' }} />
                            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <span className="font-display text-xl font-extrabold leading-none tabular-nums text-white">{fmtInt(total)}</span>
                                <span className="mt-0.5 text-[8px] uppercase tracking-wider text-white/40">{centroLabel}</span>
                            </div>
                        </div>

                        <div className="min-w-0 flex-1 space-y-0.5">
                            {legenda.map((d) => {
                                const ativo = activeKey === d.key;
                                return (
                                    <button key={d.key} type="button" onClick={() => onClick?.(d.key)}
                                        title={`${d.label} — ${fmtInt(d.count)} · ${fmtPct(pctNum(d.count, total))}`}
                                        className={cn('flex w-full items-center gap-1.5 rounded-md px-1 py-0.5 text-left transition-colors',
                                            ativo ? 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/40' : 'hover:bg-white/[0.05]',
                                            activeKey && !ativo && 'opacity-45')}>
                                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: d.cor }} />
                                        <span className="flex-1 truncate text-[12px] text-white/75">{d.label}</span>
                                        <span className="shrink-0 text-[12px] font-semibold tabular-nums text-white/85">{fmtInt(d.count)}</span>
                                        <span className="w-11 shrink-0 text-right text-[11px] tabular-nums text-white/35">{fmtPct(pctNum(d.count, total))}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* Rodapé: total de categorias + Ver todas */}
                    <div className="mt-auto flex items-center justify-between gap-2 border-t border-white/[0.06] pt-2.5">
                        <span className="text-[11px] text-white/40"><b className="tabular-nums text-white/70">{nCat}</b> categorias</span>
                        <button type="button" onClick={() => setAberto(true)}
                            className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[12px] font-semibold text-ecf-yellow/90 transition hover:bg-ecf-yellow/10 hover:text-ecf-yellow">
                            {temMais ? 'Ver todas' : 'Detalhes'} <ArrowRight size={13} />
                        </button>
                    </div>
                </>
            )}

            {aberto && (
                <DetalheDrawer
                    titulo={titulo} sub={sub} Icone={Icone} centroLabel={centroLabel}
                    ordenados={ordenados} total={total} nCat={nCat} activeKey={activeKey}
                    onClose={() => setAberto(false)}
                    onFiltrar={(key) => { onClick?.(key); setAberto(false); }}
                />
            )}
        </div>
    );
}

// ─── Drawer de detalhamento (todas as categorias + busca + ordenação + tabela + export) ───
function DetalheDrawer({ titulo, sub, Icone, centroLabel, ordenados, total, nCat, activeKey, onClose, onFiltrar }) {
    const [q, setQ] = useState('');
    const [aba, setAba] = useState('lista');   // 'lista' | 'tabela'
    const [ordem, setOrdem] = useState('qtd-desc');
    const [copiado, setCopiado] = useState(false);

    // Esc fecha.
    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const maxCount = ordenados[0]?.count || 1;
    const ql = q.trim().toLowerCase();

    const lista = useMemo(() => {
        const arr = ql ? ordenados.filter((d) => String(d.label ?? '').toLowerCase().includes(ql)) : [...ordenados];
        const byLabel = (a, b) => String(a.label ?? '').localeCompare(String(b.label ?? ''), 'pt-BR', { sensitivity: 'base' });
        if (ordem === 'qtd-desc') arr.sort((a, b) => b.count - a.count);
        else if (ordem === 'qtd-asc') arr.sort((a, b) => a.count - b.count);
        else if (ordem === 'nome-asc') arr.sort(byLabel);
        else arr.sort((a, b) => byLabel(b, a));
        return arr;
    }, [ordenados, ql, ordem]);

    const ORDENS = [
        ['qtd-desc', 'Maior quantidade'], ['qtd-asc', 'Menor quantidade'],
        ['nome-asc', 'Nome (A → Z)'], ['nome-desc', 'Nome (Z → A)'],
    ];

    const linhasCSV = () => [['Categoria', centroLabel, '%'], ...ordenados.map((d) => [d.label, d.count, fmtPct(pctNum(d.count, total))])];
    const baixarCSV = () => {
        const csv = linhasCSV().map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\r\n');
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = `distribuicao-${String(titulo).toLowerCase().replace(/\s+/g, '-')}.csv`;
        document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    };
    const copiar = () => {
        navigator.clipboard?.writeText(linhasCSV().map((r) => r.join('\t')).join('\n'))
            .then(() => { setCopiado(true); setTimeout(() => setCopiado(false), 1500); }).catch(() => {});
    };

    return (
        <div className="fixed inset-0 z-[80] flex justify-end">
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />
            <div className="relative flex h-full w-full max-w-md flex-col border-l border-white/10 bg-ecf-card shadow-2xl shadow-black/60 animate-in slide-in-from-right duration-200">
                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-3 border-b border-white/10 px-5 py-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-ecf-yellow/10 text-ecf-yellow"><Icone size={19} /></span>
                        <div className="min-w-0">
                            <h3 className="truncate font-display text-lg font-bold text-white">{titulo}</h3>
                            <p className="truncate text-[12px] text-white/45">{sub}</p>
                        </div>
                    </div>
                    <button onClick={onClose} className="shrink-0 text-white/40 transition hover:text-white"><X size={18} /></button>
                </div>

                {/* Placar + toolbar */}
                <div className="space-y-3 border-b border-white/10 px-5 py-3">
                    <div className="flex items-baseline gap-1.5">
                        <span className="font-display text-2xl font-extrabold tabular-nums text-white">{fmtInt(total)}</span>
                        <span className="text-[11px] uppercase tracking-wider text-white/40">{centroLabel}</span>
                        <span className="ml-auto text-[12px] text-white/40"><b className="text-white/70">{nCat}</b> categorias</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="relative flex-1">
                            <Search size={13} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-white/30" />
                            <input type="text" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar categoria…"
                                className="w-full rounded-lg border border-white/[0.08] bg-white/[0.03] py-2 pl-8 pr-3 text-[12px] text-white/90 outline-none transition-colors focus:border-ecf-yellow/40" />
                        </div>
                        {/* Ordenar */}
                        <MenuBotao label="Ordenar" icon={ChevronDown}>
                            {(fechar) => ORDENS.map(([k, l]) => (
                                <button key={k} type="button" onClick={() => { setOrdem(k); fechar(); }}
                                    className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12px] transition hover:bg-white/[0.06]',
                                        ordem === k ? 'text-ecf-yellow' : 'text-white/80')}>
                                    {ordem === k ? <Check size={13} /> : <span className="w-[13px]" />} {l}
                                </button>
                            ))}
                        </MenuBotao>
                        {/* Exportar */}
                        <MenuBotao label="Exportar" icon={Download} showChevron={false}>
                            {(fechar) => (<>
                                <button type="button" onClick={() => { baixarCSV(); fechar(); }}
                                    className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12px] text-white/80 transition hover:bg-white/[0.06]">
                                    <Download size={13} /> Baixar CSV
                                </button>
                                <button type="button" onClick={copiar}
                                    className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12px] text-white/80 transition hover:bg-white/[0.06]">
                                    {copiado ? <Check size={13} className="text-emerald-400" /> : <Copy size={13} />} {copiado ? 'Copiado!' : 'Copiar tabela'}
                                </button>
                            </>)}
                        </MenuBotao>
                    </div>
                    {/* Abas */}
                    <div className="flex items-center gap-1 border-b border-white/[0.06]">
                        {[['lista', 'Lista', List], ['tabela', 'Tabela', Table2]].map(([k, l, Ico]) => (
                            <button key={k} type="button" onClick={() => setAba(k)}
                                className={cn('-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-1.5 text-[12px] font-semibold transition-colors',
                                    aba === k ? 'border-ecf-yellow text-white' : 'border-transparent text-white/45 hover:text-white/80')}>
                                <Ico size={13} /> {l}
                            </button>
                        ))}
                        <span className="ml-auto text-[11px] tabular-nums text-white/30">{lista.length}/{nCat}</span>
                    </div>
                </div>

                {/* Lista / Tabela (todas as categorias) */}
                <div className="min-h-0 flex-1 overflow-y-auto px-3 py-2">
                    {lista.length === 0 && <p className="px-2 py-10 text-center text-[12px] text-white/25">Nenhuma categoria encontrada.</p>}

                    {aba === 'lista' && lista.map((d) => {
                        const p = pctNum(d.count, total);
                        const ativo = activeKey === d.key;
                        return (
                            <button key={d.key} type="button" onClick={() => onFiltrar(d.key)} title={`Filtrar a grade por: ${d.label}`}
                                className={cn('group flex w-full flex-col gap-1.5 rounded-lg px-2 py-2 text-left transition-colors',
                                    ativo ? 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/40' : 'hover:bg-white/[0.04]')}>
                                <div className="flex items-center gap-2.5">
                                    <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: d.cor }} />
                                    <span className="flex-1 truncate text-[13px] text-white/85">{d.label}</span>
                                    <span className="text-[13px] font-semibold tabular-nums text-white/90">{fmtInt(d.count)}</span>
                                    <span className="w-12 text-right text-[12px] tabular-nums text-white/50">{fmtPct(p)}</span>
                                </div>
                                <div className="ml-[22px] h-1.5 overflow-hidden rounded-full bg-white/[0.06]">
                                    <div className="h-full rounded-full" style={{ width: `${Math.max(2, (d.count / maxCount) * 100)}%`, background: d.cor }} />
                                </div>
                            </button>
                        );
                    })}

                    {aba === 'tabela' && (
                        <table className="w-full border-collapse text-left">
                            <thead>
                                <tr className="text-[10px] uppercase tracking-wider text-white/35">
                                    <th className="px-2 py-1.5 font-semibold">Categoria</th>
                                    <th className="px-2 py-1.5 text-right font-semibold">{centroLabel}</th>
                                    <th className="px-2 py-1.5 text-right font-semibold">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lista.map((d) => (
                                    <tr key={d.key} onClick={() => onFiltrar(d.key)}
                                        className={cn('cursor-pointer border-b border-white/[0.05] transition-colors hover:bg-white/[0.03]', activeKey === d.key && 'bg-ecf-yellow/[0.06]')}>
                                        <td className="px-2 py-1.5">
                                            <span className="flex items-center gap-2">
                                                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: d.cor }} />
                                                <span className="truncate text-[12.5px] text-white/85">{d.label}</span>
                                            </span>
                                        </td>
                                        <td className="px-2 py-1.5 text-right text-[12.5px] font-semibold tabular-nums text-white/90">{fmtInt(d.count)}</td>
                                        <td className="px-2 py-1.5 text-right text-[12px] tabular-nums text-white/50">{fmtPct(pctNum(d.count, total))}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* Rodapé: dica */}
                <div className="flex items-center gap-2 border-t border-white/10 px-5 py-2.5 text-[11px] text-white/40">
                    <Info size={12} className="shrink-0 text-white/30" />
                    Clique numa categoria para filtrar a grade por ela.
                </div>
            </div>
        </div>
    );
}

// Botão + popover (Radix) reutilizado p/ Ordenar e Exportar. `children` recebe uma função (fechar)=>JSX.
function MenuBotao({ label, icon: Icon, showChevron = true, children }) {
    const [open, setOpen] = useState(false);
    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            <Popover.Trigger asChild>
                <button type="button"
                    className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-white/[0.1] bg-white/[0.03] px-2.5 py-2 text-[12px] font-semibold text-white/75 transition hover:border-white/25 hover:text-white">
                    <Icon size={13} /> {label} {showChevron && <ChevronDown size={12} className="text-white/40" />}
                </button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content side="bottom" align="end" sideOffset={6}
                    className="z-[90] w-48 rounded-xl border border-white/10 bg-ecf-card p-1 text-white shadow-2xl shadow-black/50 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95">
                    {children(() => setOpen(false))}
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
