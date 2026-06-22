import { useMemo } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { PieChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { STATUS_META, STATUS_ORDEM } from './statusMeta';

// Registro tree-shakeable: pizza/donut + tooltip (mesmo conjunto do RoseChart).
echarts.use([PieChart, TooltipComponent, CanvasRenderer]);

/**
 * StatusDonut — donut de comando da distribuição de status entre TODOS os ativos
 * do mês (independe do filtro de chips). % de "No alvo" em destaque no centro,
 * anel multicor com glow por fatia. Substitui a pizza rose de status.
 *
 * Props:
 *   statusDist : { Sim, 'Em progresso', 'Não', Problema, total }
 *   height     : altura do donut em px (default 280)
 *   compacto   : true = barra 100% empilhada fina (economiza altura)
 */
export default function StatusDonut({ statusDist = { total: 0 }, height = 280, compacto = false }) {
    const total      = statusDist.total ?? 0;
    const pctNoAlvo  = total > 0 ? Math.round(((statusDist['Sim'] ?? 0) / total) * 100) : 0;

    const option = useMemo(() => ({
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'item',
            backgroundColor: 'rgba(15,17,22,0.95)',
            borderColor: 'rgba(255,255,255,0.10)',
            borderWidth: 1,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (p) => `${p.marker} ${p.name}<br/><b>${p.value}</b> ativos &nbsp;·&nbsp; ${p.percent}%`,
        },
        series: [{
            name: 'Status',
            type: 'pie',
            radius: ['58%', '80%'],
            center: ['50%', '50%'],
            avoidLabelOverlap: false,
            label: { show: false },
            labelLine: { show: false },
            itemStyle: { borderRadius: 6, borderColor: '#0f1116', borderWidth: 2 },
            emphasis: { scale: true, scaleSize: 6, itemStyle: { shadowBlur: 22 } },
            data: STATUS_ORDEM
                .map((k) => ({
                    value: statusDist[k] ?? 0,
                    name: STATUS_META[k].label,
                    itemStyle: { color: STATUS_META[k].cor, shadowBlur: 14, shadowColor: `${STATUS_META[k].cor}55` },
                }))
                .filter((d) => d.value > 0),
        }],
    }), [statusDist]);

    // Variante compacta: barra 100% empilhada fina (sem canvas)
    if (compacto) {
        return (
            <div className="flex h-3 w-full overflow-hidden rounded-full bg-white/[0.06]">
                {STATUS_ORDEM.map((k) => {
                    const v = statusDist[k] ?? 0;
                    if (v <= 0 || total <= 0) return null;
                    return (
                        <div
                            key={k}
                            className="h-full first:rounded-l-full last:rounded-r-full"
                            style={{ width: `${(v / total) * 100}%`, background: STATUS_META[k].cor, boxShadow: `0 0 10px ${STATUS_META[k].cor}44` }}
                            title={`${STATUS_META[k].label}: ${v}`}
                        />
                    );
                })}
            </div>
        );
    }

    if (total === 0) {
        return (
            <div className="flex items-center justify-center text-white/35 text-sm" style={{ height }}>
                Sem ativos no mês selecionado
            </div>
        );
    }

    return (
        <div className="relative" style={{ height }}>
            <ReactEChartsCore
                echarts={echarts}
                option={option}
                notMerge
                lazyUpdate
                style={{ height, width: '100%' }}
                opts={{ renderer: 'canvas' }}
            />
            {/* Número central: % de empresas "No alvo" */}
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span className="font-display text-4xl font-extrabold tabular-nums text-emerald-400 drop-shadow-[0_0_16px_rgba(34,197,94,0.45)]">
                    {pctNoAlvo}%
                </span>
                <span className="text-[10px] uppercase tracking-wider text-white/40">No alvo</span>
            </div>
        </div>
    );
}
