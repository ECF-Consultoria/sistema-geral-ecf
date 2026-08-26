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
 * anel multicor por fatia. Substitui a pizza rose de status.
 *
 * Props:
 *   statusDist : { Sim, 'Em progresso', 'Não', Problema, total }
 *   height     : altura do donut em px (default 280)
 *   compacto   : true = barra 100% empilhada fina (economiza altura)
 *
 * Props de PAREDE (Modo TV) — todos os defaults reproduzem o visual antigo, então os
 * call-sites de tela (Index e Painel) não mudam de aparência:
 *   fonteCentro : CSS font-size do % central (default 3rem = o antigo text-5xl). A 460px
 *                 de donut, 48px encolhe visualmente e some a 4 m de distância.
 *   fonteRotulo : CSS font-size do rótulo "No alvo" (default 11px)
 *   corFundo    : cor do separador entre fatias (default '#0f1116', a cor do card do
 *                 painel — na TV o fundo é outro, senão o vinco fica cinza claro)
 *   borda       : espessura do separador (default 2; num anel de 460px use 5-6)
 *   raio        : [interno, externo] do anel (default ['62%','88%'])
 *   interativo  : false desliga tooltip e realce de fatia — na parede não há mouse
 */
export default function StatusDonut({
    statusDist = { total: 0 },
    height = 280,
    compacto = false,
    fonteCentro = '3rem',
    fonteRotulo = '11px',
    corFundo = '#0f1116',
    borda = 2,
    raio = ['62%', '88%'],
    interativo = true,
}) {
    const total      = statusDist.total ?? 0;
    const pctNoAlvo  = total > 0 ? Math.round(((statusDist['Sim'] ?? 0) / total) * 100) : 0;

    const option = useMemo(() => ({
        backgroundColor: 'transparent',
        tooltip: interativo ? {
            trigger: 'item',
            backgroundColor: 'rgba(15,17,22,0.95)',
            borderColor: 'rgba(255,255,255,0.10)',
            borderWidth: 1,
            textStyle: { color: '#fff', fontSize: 12 },
            formatter: (p) => `${p.marker} ${p.name}<br/><b>${p.value}</b> ativos &nbsp;·&nbsp; ${p.percent}%`,
        } : { show: false },
        series: [{
            name: 'Status',
            type: 'pie',
            radius: raio,
            center: ['50%', '50%'],
            avoidLabelOverlap: false,
            label: { show: false },
            labelLine: { show: false },
            itemStyle: { borderRadius: 6, borderColor: corFundo, borderWidth: borda },
            emphasis: interativo ? { scale: true, scaleSize: 6 } : { scale: false },
            silent: !interativo,
            data: STATUS_ORDEM
                .map((k) => ({
                    value: statusDist[k] ?? 0,
                    name: STATUS_META[k].label,
                    itemStyle: { color: STATUS_META[k].cor },
                }))
                .filter((d) => d.value > 0),
        }],
    }), [statusDist, interativo, raio, corFundo, borda]);

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
                            style={{ width: `${(v / total) * 100}%`, background: STATUS_META[k].cor }}
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
        <div className="relative w-full" style={{ height }}>
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
                <span className="font-display font-extrabold leading-none tabular-nums text-emerald-400"
                      style={{ fontSize: fonteCentro }}>
                    {pctNoAlvo}%
                </span>
                <span className="mt-1 uppercase leading-none tracking-wider text-white/40"
                      style={{ fontSize: fonteRotulo }}>No alvo</span>
            </div>
        </div>
    );
}
