import { useMemo, useState } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, MarkLineComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { cn, formatCurrency } from '@/lib/utils';
import { STATUS_META } from './statusMeta';

// Registro tree-shakeable: barras + grid + tooltip + markLine (não puxa o echarts inteiro).
echarts.use([BarChart, GridComponent, TooltipComponent, MarkLineComponent, CanvasRenderer]);

// Eixo de valores compacto (12k, 1.4 mi) — ar "terminal" sem poluir.
const fmtCompacto = (v) => {
    const n = Number(v) || 0;
    if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1).replace('.0', '') + ' mi';
    if (Math.abs(n) >= 1e3) return Math.round(n / 1e3) + 'k';
    return String(Math.round(n));
};

const COR_RESTANTE = '#2a2d36';   // faltante p/ meta (mesmo cinza do DonutCard antigo)
const ALTURA_LINHA = 38;          // px por polo

/**
 * FatVsMetaChart — gráfico focal de "Faturamento vs Meta por polo".
 *
 * Dois modos (toggle interno):
 *   • Faturamento (bullet): barra de faturamento sobre o trilho da meta — a
 *     fração descoberta do trilho é o gap. Cor = cor do polo (gradiente).
 *   • Cobertura (%): barra empilhada normalizada (atingido + restante) com a
 *     linha de meta a 100% — compara polos de tamanhos muito diferentes.
 *
 * Props:
 *   polos            : lista de polos visíveis ({ polo, faturamento, meta, pct, status })
 *   corDoPolo        : mapa polo → cor (POLO_PALETTE)
 *   fonteFaturamento : 'adman' | 'csv' (subtítulo de origem)
 *   parcial          : mês corrente parcial?
 */
export default function FatVsMetaChart({ polos = [], corDoPolo = {}, fonteFaturamento = null, parcial = false }) {
    const [modo, setModo] = useState('faturamento');   // 'faturamento' | 'cobertura'

    // Maior % no topo (inverse no eixo de categorias coloca o 1º item em cima)
    const ord = useMemo(
        () => [...polos].sort((a, b) => (b.pct ?? 0) - (a.pct ?? 0)),
        [polos],
    );

    const option = useMemo(() => {
        const nomes = ord.map((p) => p.polo);

        const baseTooltip = {
            trigger: 'axis',
            axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(255,255,255,0.04)' } },
            backgroundColor: 'rgba(15,17,22,0.95)',
            borderColor: 'rgba(255,255,255,0.10)',
            borderWidth: 1,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (params) => {
                const p = ord[params?.[0]?.dataIndex];
                if (!p) return '';
                const gap = (p.meta ?? 0) - (p.faturamento ?? 0);
                const cor = corDoPolo[p.polo] ?? '#ffe600';
                const gapLinha = gap > 0
                    ? `<span style="color:#fb7185">faltam ${formatCurrency(gap)}</span>`
                    : `<span style="color:#22c55e">+${formatCurrency(-gap)} acima</span>`;
                return [
                    `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${cor};margin-right:6px"></span><b>${p.polo}</b>`,
                    `Faturamento: <b>${formatCurrency(p.faturamento)}</b>`,
                    `Meta: ${formatCurrency(p.meta)} &nbsp;·&nbsp; <b>${(p.pct ?? 0).toFixed(1)}%</b>`,
                    gapLinha,
                ].join('<br/>');
            },
        };

        const baseGrid = { left: 8, right: 26, top: 6, bottom: 4, containLabel: true };
        const baseYAxis = {
            type: 'category',
            data: nomes,
            inverse: true,
            axisTick: { show: false },
            axisLine: { lineStyle: { color: 'rgba(255,255,255,0.06)' } },
            axisLabel: { color: 'rgba(255,255,255,0.7)', fontSize: 11 },
        };

        if (modo === 'cobertura') {
            const atingido = ord.map((p) => Math.min(Math.max(p.pct ?? 0, 0), 100));
            const restante = atingido.map((v) => Math.max(100 - v, 0));
            return {
                backgroundColor: 'transparent',
                tooltip: baseTooltip,
                grid: baseGrid,
                xAxis: {
                    type: 'value', max: 100,
                    axisLabel: { formatter: '{value}%', color: 'rgba(255,255,255,0.4)', fontSize: 10 },
                    splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } },
                    axisLine: { show: false },
                },
                yAxis: baseYAxis,
                series: [
                    {
                        name: 'Atingido', type: 'bar', stack: 'cob', barWidth: '58%',
                        data: ord.map((p) => ({
                            value: Math.min(Math.max(p.pct ?? 0, 0), 100),
                            itemStyle: {
                                color: STATUS_META[p.status]?.cor ?? '#ffe600',
                                borderRadius: [4, 0, 0, 4],
                            },
                        })),
                    },
                    {
                        name: 'Restante', type: 'bar', stack: 'cob', barWidth: '58%',
                        data: restante,
                        itemStyle: { color: COR_RESTANTE, borderRadius: [0, 4, 4, 0] },
                        markLine: {
                            symbol: 'none', silent: true,
                            lineStyle: { type: 'dashed', color: '#ffe600', width: 1.5, opacity: 0.7 },
                            label: { formatter: 'Meta', color: '#ffe600', fontSize: 10, position: 'end' },
                            data: [{ xAxis: 100 }],
                        },
                    },
                ],
                animationEasing: 'cubicOut',
                animationDuration: 600,
            };
        }

        // ── Modo Faturamento (bullet): trilho = meta, barra = faturamento ──
        const maxVal = Math.max(...ord.map((p) => Math.max(p.faturamento ?? 0, p.meta ?? 0)), 1);
        return {
            backgroundColor: 'transparent',
            tooltip: baseTooltip,
            grid: baseGrid,
            xAxis: {
                type: 'value', max: maxVal * 1.04,
                axisLabel: { formatter: fmtCompacto, color: 'rgba(255,255,255,0.4)', fontSize: 10 },
                splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } },
                axisLine: { show: false },
            },
            yAxis: baseYAxis,
            series: [
                // Trilho da meta (faint) — o que sobra do trilho é o gap p/ a meta
                {
                    name: 'Meta', type: 'bar', barWidth: '58%', barGap: '-100%', z: 1, silent: true,
                    data: ord.map((p) => p.meta ?? 0),
                    itemStyle: { color: 'rgba(255,255,255,0.05)', borderRadius: [0, 6, 6, 0] },
                },
                // Faturamento sobre o trilho (gradiente na cor do polo)
                {
                    name: 'Faturamento', type: 'bar', barWidth: '58%', barGap: '-100%', z: 2,
                    data: ord.map((p) => {
                        const cor = corDoPolo[p.polo] ?? '#ffe600';
                        return {
                            value: p.faturamento ?? 0,
                            itemStyle: {
                                borderRadius: [0, 6, 6, 0],
                                color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
                                    { offset: 0, color: `${cor}99` },
                                    { offset: 1, color: cor },
                                ]),
                            },
                        };
                    }),
                },
            ],
            animationEasing: 'cubicOut',
            animationDuration: 600,
        };
    }, [ord, corDoPolo, modo]);

    const alturaChart = Math.max(260, ord.length * ALTURA_LINHA);

    const origem = fonteFaturamento === 'csv'
        ? 'fonte: TGMV oficial (CSV)'
        : parcial
            ? 'valores parciais — Adman ao vivo'
            : 'fonte: Adman';

    return (
        <div>
            {/* Toggle de métrica + origem */}
            <div className="mb-4 flex items-center justify-between gap-3">
                <div className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                    {[
                        { key: 'faturamento', label: 'Faturamento' },
                        { key: 'cobertura',   label: 'Cobertura da meta' },
                    ].map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setModo(t.key)}
                            className={cn(
                                'rounded-md px-3 py-1 text-xs font-semibold transition-colors',
                                modo === t.key ? 'bg-ecf-yellow text-black' : 'text-white/55 hover:text-white/80',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <span className="text-white/30 text-[11px]">{origem}</span>
            </div>

            {ord.length === 0 ? (
                <div className="flex items-center justify-center text-white/35 text-sm" style={{ height: 280 }}>
                    Sem faturamento no mês selecionado
                </div>
            ) : (
                <div className="max-h-[600px] overflow-y-auto pr-1">
                    <ReactEChartsCore
                        echarts={echarts}
                        option={option}
                        notMerge
                        lazyUpdate
                        style={{ height: alturaChart, width: '100%' }}
                        opts={{ renderer: 'canvas' }}
                    />
                </div>
            )}
        </div>
    );
}
