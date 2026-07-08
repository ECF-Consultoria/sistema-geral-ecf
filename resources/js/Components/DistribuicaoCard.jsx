import { useMemo, useState } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { PieChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { Search, ChevronDown, ChevronUp } from 'lucide-react';
import { cn } from '@/lib/utils';

// Registro tree-shakeable: pizza/donut + tooltip (mesmo conjunto do StatusDonut).
echarts.use([PieChart, TooltipComponent, CanvasRenderer]);

/**
 * DistribuicaoCard — PADRÃO DEFINITIVO de gráfico de distribuição do Painel Polos.
 *
 * Um único componente ADAPTATIVO que escolhe o visual pela cardinalidade:
 *   · N ≤ donutMax  → DONUT echarts (part-to-whole legível) + legenda-tabela ao lado.
 *   · N  > donutMax → BARRAS HORIZONTAIS RANQUEADAS (comprimento sobre baseline comum),
 *                     o encoding que o olho lê melhor e que acomoda 30/40/50+ categorias.
 *
 * O card tem ALTURA FIXA: a legenda/lista de barras rola internamente (overflow-y) — nunca
 * cresce sem limite (era o bug do DonutIndicador: 1 botão por categoria sem cap → ~600px).
 * Acima de `buscaMin` categorias surge uma busca por nome no cabeçalho; o card mostra o
 * Top-N com rodapé "Ver todas (N)" que expande a lista completa NO MESMO card (scroll) —
 * NUNCA existe balde "Outros" que esconde dado. 100% das categorias sempre acessíveis.
 *
 * Retro-compatível com o antigo DonutIndicador: { titulo, icone, dados, onClick, activeKey,
 * height, centroLabel }. Toda a adaptação vem de props novas com defaults que reproduzem o
 * comportamento atual para os call-sites de baixa cardinalidade (CardM1/CardFat do MetasPanel).
 *
 * dados: [{ key, label, count, cor? }]. activeKey destaca a fatia/barra e esmaece as demais.
 */

const COR_NEUTRA = 'rgba(255,255,255,0.28)'; // fill padrão quando a categoria não traz cor
const pctDe = (n, total) => (total > 0 ? Math.round((n / total) * 100) : 0);

export default function DistribuicaoCard({
    // ── Retro-compat (assinatura do DonutIndicador) ──
    titulo,
    icone: Icone,
    dados = [],
    onClick,
    activeKey = null,
    height = 180,
    centroLabel = 'total',
    // ── Novas (configuração; defaults reproduzem o donut+legenda de antes) ──
    modo = 'auto',        // 'auto' | 'donut' | 'barras'
    donutMax = 7,         // até este nº de categorias (count>0) usa donut; acima, barras
    topN = 8,             // quantas linhas/barras antes do "Ver todas"
    buscaMin = 12,        // a busca por nome aparece a partir deste nº de categorias
    busca = 'auto',       // 'auto' | true | false
    ordenar = true,       // reordena por count desc (af.optionsFor vem alfabético)
    alturaFixa,           // max-height do bloco rolável (senão deriva de `height`)
    mostrarPct = true,    // mostra % do total ao lado do count
    vazioLabel = 'Sem dados',
}) {
    const [q, setQ] = useState('');
    const [expandido, setExpandido] = useState(false);

    const comValor = useMemo(() => dados.filter((d) => (d.count || 0) > 0), [dados]);
    const total = useMemo(() => dados.reduce((a, d) => a + (d.count || 0), 0), [dados]);
    const nCat = comValor.length;

    // Ranking por contagem (o ranking É o insight); count===0 vão pro fim, sem sumir.
    const ordenados = useMemo(() => {
        if (!ordenar) return dados;
        return [...dados].sort((a, b) =>
            (b.count || 0) - (a.count || 0) ||
            String(a.label ?? '').localeCompare(String(b.label ?? ''), 'pt-BR', { sensitivity: 'base' }));
    }, [dados, ordenar]);

    const usaBarras = modo === 'barras' || (modo === 'auto' && nCat > donutMax);
    const maxCount = useMemo(() => Math.max(1, ...comValor.map((d) => d.count || 0)), [comValor]);

    // Busca: filtra só o que é EXIBIDO (não altera onClick nem a fonte).
    const mostrarBusca = busca === true || (busca === 'auto' && nCat >= buscaMin);
    const ql = q.trim().toLowerCase();
    const filtrados = ql
        ? ordenados.filter((d) => String(d.label ?? '').toLowerCase().includes(ql))
        : ordenados;

    // Top-N + "Ver todas" (ignorado enquanto há busca ativa).
    const excedeTopN = !ql && filtrados.length > topN;
    const visiveis = (excedeTopN && !expandido) ? filtrados.slice(0, topN) : filtrados;

    const listMax = alturaFixa ?? (usaBarras ? Math.max(height, 220) : height);
    const comValorOrdenado = useMemo(() => ordenados.filter((d) => (d.count || 0) > 0), [ordenados]);

    // ── Donut (echarts) ──
    const option = useMemo(() => ({
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            backgroundColor: 'rgba(15,17,22,0.95)',
            borderColor: 'rgba(255,255,255,0.10)',
            borderWidth: 1,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (p) => `${p.marker} ${p.name}<br/><b>${p.value}</b> · ${p.percent}%`,
        },
        series: [{
            type: 'pie',
            radius: ['60%', '86%'],
            center: ['50%', '50%'],
            avoidLabelOverlap: false,
            label: { show: false },
            labelLine: { show: false },
            itemStyle: { borderRadius: 6, borderColor: '#0f1116', borderWidth: 2 },
            emphasis: { scale: true, scaleSize: 6 },
            data: comValorOrdenado.map((d) => ({
                value: d.count,
                name: d.label,
                chave: d.key,
                itemStyle: { color: d.cor || COR_NEUTRA, opacity: activeKey && activeKey !== d.key ? 0.3 : 1 },
            })),
        }],
    }), [comValorOrdenado, activeKey]);

    const onEvents = { click: (p) => { const k = p?.data?.chave; if (k != null) onClick?.(k); } };

    // ── Cabeçalho (título + placar no modo barras + busca opcional) ──
    // No modo barras não há centro de donut, então o total (com centroLabel) vira placar no
    // cabeçalho — preserva o "número-âncora" (ex.: 282 empresas · 409 pendências).
    const cabecalho = (
        <div className="mb-2 flex items-start justify-between gap-2">
            <div className="min-w-0">
                <h3 className="flex min-w-0 items-center gap-1.5 text-sm font-semibold text-white/70">
                    {Icone && <Icone size={14} className="shrink-0 text-ecf-yellow" />}
                    <span className="truncate">{titulo}</span>
                </h3>
                {usaBarras && total > 0 && (
                    <div className="mt-0.5 flex items-baseline gap-1.5">
                        <span className="font-display text-2xl font-extrabold leading-none tabular-nums text-white">{total}</span>
                        <span className="text-[10px] uppercase tracking-wider text-white/40">{centroLabel}</span>
                    </div>
                )}
            </div>
            {mostrarBusca && total > 0 && (
                <div className="relative shrink-0">
                    <Search size={11} className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-white/30" />
                    <input
                        type="text" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar…"
                        className="w-24 rounded-lg border border-white/[0.08] bg-white/[0.03] py-1 pl-6 pr-2 text-[11px] text-white/90 outline-none transition-colors focus:w-32 focus:border-ecf-yellow/40"
                    />
                </div>
            )}
        </div>
    );

    if (total === 0) {
        return (
            <div>
                {cabecalho}
                <div className="flex items-center justify-center text-sm text-white/30" style={{ height }}>
                    {vazioLabel}
                </div>
            </div>
        );
    }

    // Rodapé "Ver todas / Ver menos" (progressive disclosure — nunca vira "Outros").
    const rodape = excedeTopN && (
        <button type="button" onClick={() => setExpandido((v) => !v)}
            className="mt-1.5 flex w-full items-center justify-center gap-1 rounded-lg py-1 text-[11px] font-semibold text-white/45 transition hover:bg-white/[0.04] hover:text-white/80">
            {expandido
                ? <>Ver menos <ChevronUp size={12} /></>
                : <>Ver todas ({filtrados.length}) <ChevronDown size={12} /></>}
        </button>
    );

    const semResultado = ql && filtrados.length === 0 && (
        <p className="px-2 py-4 text-center text-[11px] text-white/25">Nenhuma categoria encontrada.</p>
    );

    // ── Linha de LEGENDA (modo donut): chip de cor + label + count + % ──
    const LinhaLegenda = (d) => {
        const desab = !d.count && activeKey !== d.key;
        return (
            <button key={d.key} type="button" disabled={desab} onClick={() => onClick?.(d.key)}
                title={`Filtrar: ${d.label}`}
                className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1 text-left transition-colors',
                    desab ? 'cursor-default' : 'hover:bg-white/[0.06]',
                    activeKey === d.key && 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/50',
                    activeKey && activeKey !== d.key && 'opacity-45',
                    !d.count && 'opacity-40')}>
                <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: d.cor || COR_NEUTRA }} />
                <span className="flex-1 truncate text-[12px] text-white/75">{d.label}</span>
                <span className="text-[12px] font-semibold tabular-nums text-white/90">{d.count ?? 0}</span>
                {mostrarPct && <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-white/35">{pctDe(d.count, total)}%</span>}
            </button>
        );
    };

    // ── Linha de BARRA (modo barras): label · trilho+preenchimento · count · % ──
    const LinhaBarra = (d) => {
        const desab = !d.count && activeKey !== d.key;
        const w = Math.max(d.count ? 3 : 0, (d.count / maxCount) * 100); // piso p/ barra clicável
        return (
            <button key={d.key} type="button" disabled={desab} onClick={() => onClick?.(d.key)}
                title={`${d.label} — ${d.count} · ${pctDe(d.count, total)}% · filtrar`}
                className={cn('group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition-colors',
                    desab ? 'cursor-default' : 'hover:bg-white/[0.04]',
                    activeKey === d.key && 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/50',
                    activeKey && activeKey !== d.key && 'opacity-45',
                    !d.count && 'opacity-40')}>
                <span className="w-24 shrink-0 truncate text-[12px] text-white/80 sm:w-28" title={d.label}>{d.label}</span>
                <div className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-white/[0.06]">
                    <div className="h-full rounded-full transition-all duration-500"
                        style={{ width: `${w}%`, background: d.cor || COR_NEUTRA }} />
                </div>
                <span className="w-7 shrink-0 text-right text-[12px] font-semibold tabular-nums text-white/90">{d.count ?? 0}</span>
                {mostrarPct && <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-white/35">{pctDe(d.count, total)}%</span>}
            </button>
        );
    };

    // ── Render: BARRAS (largura total) ──
    if (usaBarras) {
        return (
            <div>
                {cabecalho}
                <div className="space-y-0.5 overflow-y-auto pr-1" style={{ maxHeight: listMax }}>
                    {visiveis.map(LinhaBarra)}
                    {semResultado}
                </div>
                {rodape}
            </div>
        );
    }

    // ── Render: DONUT + legenda-tabela ──
    return (
        <div>
            {cabecalho}
            <div className="grid grid-cols-1 items-center gap-2 sm:grid-cols-2">
                <div className="relative" style={{ height }}>
                    <ReactEChartsCore echarts={echarts} option={option} notMerge lazyUpdate onEvents={onEvents}
                        style={{ height, width: '100%' }} opts={{ renderer: 'canvas' }} />
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span className="font-display text-3xl font-extrabold tabular-nums text-white">{total}</span>
                        <span className="mt-0.5 text-[10px] uppercase tracking-wider text-white/40">{centroLabel}</span>
                    </div>
                </div>

                <div>
                    <div className="space-y-0.5 overflow-y-auto pr-1" style={{ maxHeight: listMax }}>
                        {visiveis.map(LinhaLegenda)}
                        {semResultado}
                    </div>
                    {rodape}
                </div>
            </div>
        </div>
    );
}
