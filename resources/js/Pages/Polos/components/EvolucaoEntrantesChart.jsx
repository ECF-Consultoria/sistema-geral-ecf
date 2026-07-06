import { useMemo, useState } from 'react';
import ReactEChartsCore from 'echarts-for-react/lib/core';
import * as echarts from 'echarts/core';
import { BarChart, LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { TrendingUp } from 'lucide-react';
import { cn } from '@/lib/utils';

// Registro tree-shakeable: barras + linhas + grid + tooltip + legenda (não puxa o echarts inteiro).
echarts.use([BarChart, LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const COR_REAL   = '#ffe600';   // realizado (amarelo ECF)
const COR_META   = '#9ca3af';   // meta (neutro tracejado)
const COR_BATEU  = '#22c55e';   // mês que bateu a meta
const COR_BARRA  = 'rgba(255,255,255,0.28)';

/**
 * EvolucaoEntrantesChart — narrativa temporal dos entrantes. Dois modos:
 *   • Acumulado: linha de realizado acumulado (amarela, área) vs meta acumulada
 *     (tracejada) — mostra se estamos ganhando/perdendo terreno no ano. Se o último
 *     mês é o corrente, uma linha-fantasma tracejada projeta o fechamento no ritmo atual.
 *   • Mensal: barras de entrantes por mês (verde quando bateu a meta do mês, amarelo no
 *     mês selecionado) vs linha da meta do mês.
 *
 * Props:
 *   linhas    : [{ ym, label, realizadoMes, metaMes, acumReal, acumMeta }] em ordem ASC
 *   selecionadoYm : mês em foco (destaca a barra)
 *   projecao  : { ym, projAcum } | null — projeção do mês corrente (só faz sentido no acumulado)
 *   onSelectMes : (ym) => void — clique numa barra troca o mês global
 */
export default function EvolucaoEntrantesChart({ linhas = [], selecionadoYm = null, projecao = null, onSelectMes }) {
    const [modo, setModo] = useState('acumulado'); // 'acumulado' | 'mensal'

    const option = useMemo(() => {
        const labels = linhas.map((l) => l.label);
        // Ancora a projeção pelo ym (não pela posição): robusto se houver meses futuros no array.
        const idxProj = projecao ? linhas.findIndex((l) => l.ym === projecao.ym) : -1;
        const temProjecao = idxProj >= 1; // precisa do mês anterior p/ traçar o segmento

        const baseEixos = {
            grid: { left: 6, right: 14, top: 28, bottom: 4, containLabel: true },
            xAxis: {
                type: 'category', data: labels, boundaryGap: modo === 'mensal',
                axisTick: { show: false },
                axisLine: { lineStyle: { color: 'rgba(255,255,255,0.08)' } },
                axisLabel: { color: 'rgba(255,255,255,0.5)', fontSize: 10 },
            },
            yAxis: {
                type: 'value', minInterval: 1,
                axisLabel: { color: 'rgba(255,255,255,0.35)', fontSize: 10 },
                splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } },
            },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(255,255,255,0.04)' } },
                backgroundColor: 'rgba(15,17,22,0.95)',
                borderColor: 'rgba(255,255,255,0.10)',
                borderWidth: 1,
                textStyle: { color: '#fff', fontSize: 12 },
            },
            legend: {
                top: 0, right: 0, icon: 'roundRect', itemWidth: 10, itemHeight: 10,
                textStyle: { color: 'rgba(255,255,255,0.55)', fontSize: 11 },
            },
        };

        if (modo === 'mensal') {
            return {
                backgroundColor: 'transparent',
                ...baseEixos,
                legend: { ...baseEixos.legend, data: ['Entrantes', 'Meta do mês'] },
                series: [
                    {
                        name: 'Entrantes', type: 'bar', barWidth: '52%', z: 2,
                        data: linhas.map((l, i) => ({
                            value: l.realizadoMes,
                            itemStyle: {
                                borderRadius: [4, 4, 0, 0],
                                color: l.ym === selecionadoYm ? COR_REAL
                                    : (l.metaMes > 0 && l.realizadoMes >= l.metaMes) ? COR_BATEU : COR_BARRA,
                            },
                        })),
                    },
                    {
                        name: 'Meta do mês', type: 'line', z: 3, symbol: 'circle', symbolSize: 5,
                        data: linhas.map((l) => l.metaMes || 0),
                        lineStyle: { color: COR_META, width: 1.5, type: 'dashed' },
                        itemStyle: { color: COR_META },
                    },
                ],
                animationDuration: 500,
            };
        }

        // ── Acumulado ──
        const serieProj = temProjecao
            ? linhas.map((l, i) => (i === idxProj - 1 ? l.acumReal : i === idxProj ? projecao.projAcum : null))
            : null;

        return {
            backgroundColor: 'transparent',
            ...baseEixos,
            legend: { ...baseEixos.legend, data: ['Realizado', 'Meta acum.', ...(serieProj ? ['Projeção'] : [])] },
            series: [
                {
                    name: 'Realizado', type: 'line', z: 3, smooth: false, symbol: 'circle', symbolSize: 6,
                    data: linhas.map((l) => l.acumReal),
                    lineStyle: { color: COR_REAL, width: 2.5 },
                    itemStyle: { color: COR_REAL },
                    areaStyle: {
                        color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                            { offset: 0, color: 'rgba(255,230,0,0.22)' },
                            { offset: 1, color: 'rgba(255,230,0,0)' },
                        ]),
                    },
                },
                {
                    name: 'Meta acum.', type: 'line', z: 2, symbol: 'none',
                    data: linhas.map((l) => l.acumMeta),
                    lineStyle: { color: COR_META, width: 1.5, type: 'dashed' },
                    itemStyle: { color: COR_META },
                },
                ...(serieProj ? [{
                    name: 'Projeção', type: 'line', z: 4, symbol: 'circle', symbolSize: 5, connectNulls: true,
                    data: serieProj,
                    lineStyle: { color: COR_REAL, width: 1.5, type: 'dotted', opacity: 0.8 },
                    itemStyle: { color: COR_REAL, opacity: 0.8 },
                }] : []),
            ],
            animationDuration: 500,
        };
    }, [linhas, selecionadoYm, projecao, modo]);

    const onEvents = {
        click: (p) => {
            const l = linhas[p?.dataIndex];
            if (l && onSelectMes) onSelectMes(l.ym);
        },
    };

    return (
        <div>
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="flex items-center gap-1.5 text-sm font-semibold text-white/80">
                    <TrendingUp size={15} className="text-ecf-yellow" /> Evolução de entrantes
                </h3>
                <div className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                    {[['acumulado', 'Acumulado'], ['mensal', 'Por mês']].map(([k, lbl]) => (
                        <button key={k} type="button" onClick={() => setModo(k)}
                            className={cn('rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors',
                                modo === k ? 'bg-ecf-yellow text-black' : 'text-white/55 hover:text-white/80')}>
                            {lbl}
                        </button>
                    ))}
                </div>
            </div>

            {linhas.length === 0 ? (
                <div className="flex items-center justify-center text-white/35 text-sm" style={{ height: 220 }}>
                    Sem histórico de entrantes ainda
                </div>
            ) : (
                <>
                    <ReactEChartsCore
                        echarts={echarts} option={option} notMerge lazyUpdate onEvents={onEvents}
                        style={{ height: 240, width: '100%' }} opts={{ renderer: 'canvas' }} />
                    <p className="mt-1 text-center text-[10px] text-white/30">
                        {modo === 'acumulado' && projecao
                            ? `No ritmo atual, projeção de ${projecao.projAcum} entrantes acumulados no mês corrente · clique numa barra/ponto p/ focar o mês`
                            : 'Clique num mês para focá-lo no painel'}
                    </p>
                </>
            )}
        </div>
    );
}
