import { useMemo } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { PieChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { LabelLayout } from 'echarts/features';
import { CanvasRenderer } from 'echarts/renderers';
import { cn, formatCurrency } from '@/lib/utils';

// Registro tree-shakeable: só o que a pizza rose precisa (mantém o bundle enxuto).
echarts.use([PieChart, TooltipComponent, LabelLayout, CanvasRenderer]);

/**
 * RoseChart — pizza "nightingale rose" via ECharts (quick 260619-dce).
 *
 * Replica o demo oficial "pie-custom":
 *   - roseType:'radius' (ângulo E raio proporcionais ao valor)
 *   - glow via shadowBlur
 *   - animação de entrada elasticOut com escala + delay aleatório por fatia
 *   - tooltip ao passar o mouse
 * Diferença proposital: cores vêm de slices[].color (POLO_PALETTE multicor),
 * não o vermelho/visualMap do demo.
 *
 * Props:
 *   slices     : Array<{ color: string, value: number|string, label?: string }>
 *   height     : altura do container em px (default 300)
 *   money      : true = formata valores como BRL no tooltip (default true)
 *   emptyLabel : texto exibido quando não há valores positivos
 *   className
 */
export default function RoseChart({
    slices     = [],
    height     = 300,
    money      = true,
    emptyLabel = 'Sem dados no mês selecionado',
    className,
}) {
    // Coage para número, descarta não-positivos e ordena asc (igual ao demo)
    const valid = useMemo(
        () => slices
            .map((s) => ({ ...s, value: Math.max(Number(s.value) || 0, 0) }))
            .filter((s) => s.value > 0)
            .sort((a, b) => a.value - b.value),
        [slices],
    );

    const option = useMemo(() => {
        const fmt = (v) => (money ? formatCurrency(v) : `${v}`);
        return {
            backgroundColor: 'transparent',
            tooltip: {
                trigger: 'item',
                backgroundColor: 'rgba(15,17,22,0.95)',
                borderColor: 'rgba(255,255,255,0.10)',
                borderWidth: 1,
                textStyle: { color: '#fff', fontSize: 12 },
                formatter: (p) => `${p.marker} ${p.name}<br/><b>${fmt(p.value)}</b> &nbsp;·&nbsp; ${p.percent}%`,
            },
            series: [{
                name: 'Distribuição',
                type: 'pie',
                radius: '62%',
                center: ['50%', '52%'],
                roseType: 'radius',
                data: valid.map((s) => ({
                    value: s.value,
                    name: s.label ?? '',
                    itemStyle: { color: s.color },
                })),
                label: {
                    color: 'rgba(255,255,255,0.55)',
                    fontSize: 11,
                    formatter: '{b}\n{d}%',
                },
                labelLine: {
                    lineStyle: { color: 'rgba(255,255,255,0.25)' },
                    smooth: 0.2,
                    length: 10,
                    length2: 18,
                },
                itemStyle: {
                    borderRadius: 4,
                    shadowBlur: 80,
                    shadowColor: 'rgba(0,0,0,0.5)',
                },
                // ── O "dinâmico" do demo ──
                animationType: 'scale',
                animationEasing: 'elasticOut',
                animationDelay: () => Math.random() * 200,
            }],
        };
    }, [valid, money]);

    // Estado vazio (mês sem faturamento): não renderiza o canvas, mostra aviso.
    if (valid.length === 0) {
        return (
            <div
                className={cn('flex items-center justify-center text-white/35 text-sm', className)}
                style={{ height }}
            >
                {emptyLabel}
            </div>
        );
    }

    return (
        <ReactEChartsCore
            echarts={echarts}
            option={option}
            notMerge
            lazyUpdate
            style={{ height, width: '100%' }}
            opts={{ renderer: 'canvas' }}
            className={className}
        />
    );
}
