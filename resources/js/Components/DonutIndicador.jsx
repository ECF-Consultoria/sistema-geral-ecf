import { useMemo } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { PieChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { cn } from '@/lib/utils';

// Mesmo conjunto tree-shakeable do StatusDonut (donut de comando do cockpit).
echarts.use([PieChart, TooltipComponent, CanvasRenderer]);

/**
 * DonutIndicador — indicador ACIONÁVEL no estilo dos gráficos do cockpit (donut echarts
 * com número central + emphasis no hover, "Distribuição de status") somado à legenda
 * clicável estilo "Coorte M1". Cada fatia/linha da legenda é um filtro: onClick(key).
 *
 * dados: [{ key, label, count, cor }]. activeKey destaca a fatia isolada (dim as outras).
 */
export default function DonutIndicador({ titulo, icone: Icone, dados = [], onClick, activeKey = null, height = 180, centroLabel = 'total' }) {
    const comValor = dados.filter((d) => d.count > 0);
    const total = dados.reduce((a, d) => a + (d.count || 0), 0);

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
            data: comValor.map((d) => ({
                value: d.count,
                name: d.label,
                chave: d.key,
                itemStyle: { color: d.cor, opacity: activeKey && activeKey !== d.key ? 0.3 : 1 },
            })),
        }],
    }), [comValor, activeKey]);

    const onEvents = { click: (p) => { const k = p?.data?.chave; if (k != null) onClick?.(k); } };

    return (
        <div>
            <h3 className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-white/70">
                {Icone && <Icone size={14} className="text-ecf-yellow" />} {titulo}
            </h3>
            <div className="grid grid-cols-1 items-center gap-2 sm:grid-cols-2">
                <div className="relative" style={{ height }}>
                    {total > 0 ? (
                        <>
                            <ReactEChartsCore echarts={echarts} option={option} notMerge lazyUpdate onEvents={onEvents}
                                style={{ height, width: '100%' }} opts={{ renderer: 'canvas' }} />
                            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <span className="font-display text-3xl font-extrabold tabular-nums text-white">{total}</span>
                                <span className="mt-0.5 text-[10px] uppercase tracking-wider text-white/40">{centroLabel}</span>
                            </div>
                        </>
                    ) : (
                        <div className="flex h-full items-center justify-center text-sm text-white/30">Sem dados</div>
                    )}
                </div>

                {/* Legenda clicável (cada linha é um filtro) */}
                <div className="space-y-0.5">
                    {dados.map((d) => {
                        const desab = !d.count && activeKey !== d.key;
                        return (
                            <button key={d.key} type="button" disabled={desab} onClick={() => onClick?.(d.key)}
                                title={`Filtrar: ${d.label}`}
                                className={cn('flex w-full items-center gap-2 rounded-lg px-2 py-1 text-left transition-colors',
                                    desab ? 'cursor-default' : 'hover:bg-white/[0.06]',
                                    activeKey === d.key && 'bg-ecf-yellow/10 ring-1 ring-ecf-yellow/50',
                                    !d.count && 'opacity-40')}>
                                <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: d.cor }} />
                                <span className="flex-1 truncate text-[12px] text-white/75">{d.label}</span>
                                <span className="text-[12px] font-semibold tabular-nums text-white/90">{d.count ?? 0}</span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
