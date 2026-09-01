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
 * Frase de origem do número. Exportada porque o Modo TV mostra o MESMO rótulo no
 * cabeçalho do card (lá o gráfico é controlado e não desenha cabeçalho próprio) —
 * duplicar a string faria as duas telas divergirem na primeira troca de fonte.
 */
export function origemFaturamento(fonteFaturamento, parcial) {
    if (fonteFaturamento === 'csv') return 'fonte: TGMV oficial (CSV)';
    return parcial ? 'valores parciais — Adman ao vivo' : 'fonte: Adman';
}

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
 *
 * Props de PAREDE (Modo TV) — todos os defaults reproduzem o visual de tela, então o
 * call-site do Painel não muda de aparência:
 *   parede         : contraste de parede no nome do polo (lido a 4-5 m, não a 60 cm)
 *   modo           : 'faturamento' | 'cobertura' CONTROLADO. Com ele o cabeçalho interno
 *                    (toggle + origem) não é renderizado — na parede o toggle mora no
 *                    cabeçalho do card, e o gráfico recebe a altura inteira da faixa.
 *   altura         : altura do canvas em px JÁ RESOLVIDOS. Sem isto ela sai da contagem
 *                    de polos e a caixa rola — e canvas não aceita % que chega como 0 no
 *                    primeiro layout do fullscreen.
 *   fonteCategoria : px do nome do polo no eixo Y
 *   fonteEixo      : px da escala de valores no eixo X
 *   fonteValor     : px do rótulo no fim da barra; null = sem rótulo, que é o visual de
 *                    tela (lá o valor vive no tooltip — e a parede não tem mouse)
 *   interativo     : false desliga tooltip e realce de barra
 */
export default function FatVsMetaChart({
    polos = [],
    corDoPolo = {},
    fonteFaturamento = null,
    parcial = false,
    parede = false,
    modo = null,
    altura = null,
    fonteCategoria = 11,
    fonteEixo = 10,
    fonteValor = null,
    interativo = true,
}) {
    // `modo` controlado vence o estado local: quem controla (a parede) some com o toggle.
    const [modoLocal, setModoLocal] = useState('faturamento');
    const modoAtivo = modo ?? modoLocal;

    // Maior % no topo (inverse no eixo de categorias coloca o 1º item em cima)
    const ord = useMemo(
        () => [...polos].sort((a, b) => (b.pct ?? 0) - (a.pct ?? 0)),
        [polos],
    );

    const option = useMemo(() => {
        const nomes = ord.map((p) => p.polo);

        const baseTooltip = interativo ? {
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
        } : { show: false };

        // Com rótulo no fim da barra a margem direita deixa de ser decorativa: é ali que o
        // número é desenhado, e containLabel só reserva espaço para rótulo de EIXO.
        const baseGrid = { left: 8, right: fonteValor ? 60 : 26, top: 6, bottom: 4, containLabel: true };
        const baseYAxis = {
            type: 'category',
            data: nomes,
            inverse: true,
            axisTick: { show: false },
            axisLine: { lineStyle: { color: 'rgba(255,255,255,0.06)' } },
            axisLabel: {
                color: parede ? 'rgba(255,255,255,0.9)' : 'rgba(255,255,255,0.7)',
                fontSize: fonteCategoria,
                fontWeight: parede ? 600 : 'normal',
                // O default 'auto' OMITE rótulo que se sobrepõe — na parede isso é corte
                // de dado calado: a barra do polo fica lá, sem nome. Quem se ajusta é a
                // fonte (o call-site dá o teto pela altura da fileira), nunca a contagem.
                ...(parede ? { interval: 0 } : {}),
            },
        };
        const baseSerie = {
            barWidth: '58%',
            emphasis: interativo ? undefined : { disabled: true },
            silent: !interativo,
        };

        if (modoAtivo === 'cobertura') {
            const atingido = ord.map((p) => Math.min(Math.max(p.pct ?? 0, 0), 100));
            const restante = atingido.map((v) => Math.max(100 - v, 0));
            return {
                backgroundColor: 'transparent',
                tooltip: baseTooltip,
                grid: baseGrid,
                xAxis: {
                    type: 'value', max: 100,
                    axisLabel: { formatter: '{value}%', color: 'rgba(255,255,255,0.4)', fontSize: fonteEixo },
                    splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } },
                    axisLine: { show: false },
                },
                yAxis: baseYAxis,
                series: [
                    {
                        ...baseSerie,
                        name: 'Atingido', type: 'bar', stack: 'cob',
                        data: ord.map((p) => ({
                            value: Math.min(Math.max(p.pct ?? 0, 0), 100),
                            itemStyle: {
                                color: STATUS_META[p.status]?.cor ?? '#ffe600',
                                borderRadius: [4, 0, 0, 4],
                            },
                        })),
                    },
                    {
                        ...baseSerie,
                        name: 'Restante', type: 'bar', stack: 'cob',
                        // O rótulo do % vai na ponta do RESTANTE (x = 100), não na do
                        // atingido: assim os números saem numa coluna alinhada em vez de
                        // escadinha, e um polo em 8% não perde o número dentro da barra.
                        data: restante.map((v, i) => ({
                            value: v,
                            label: fonteValor ? { color: STATUS_META[ord[i].status]?.cor ?? '#ffe600' } : undefined,
                        })),
                        itemStyle: { color: COR_RESTANTE, borderRadius: [0, 4, 4, 0] },
                        label: fonteValor ? {
                            show: true, position: 'right', distance: 10,
                            fontSize: fonteValor, fontWeight: 800,
                            formatter: (p) => `${Math.round(ord[p.dataIndex]?.pct ?? 0)}%`,
                        } : { show: false },
                        markLine: {
                            symbol: 'none', silent: true,
                            lineStyle: { type: 'dashed', color: '#ffe600', width: 1.5, opacity: 0.7 },
                            // Na parede o rótulo "Meta" cairia em cima do % de quem bate 100%.
                            label: fonteValor
                                ? { show: false }
                                : { formatter: 'Meta', color: '#ffe600', fontSize: 10, position: 'end' },
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
                // Folga extra à direita quando há rótulo: sem ela o número da maior barra
                // (justamente a que interessa) sai desenhado meio fora do canvas.
                type: 'value', max: maxVal * (fonteValor ? 1.12 : 1.04),
                axisLabel: { formatter: fmtCompacto, color: 'rgba(255,255,255,0.4)', fontSize: fonteEixo },
                splitLine: { lineStyle: { color: 'rgba(255,255,255,0.05)' } },
                axisLine: { show: false },
            },
            yAxis: baseYAxis,
            series: [
                // Trilho da meta (faint) — o que sobra do trilho é o gap p/ a meta
                {
                    ...baseSerie,
                    name: 'Meta', type: 'bar', barGap: '-100%', z: 1, silent: true,
                    data: ord.map((p) => p.meta ?? 0),
                    itemStyle: { color: 'rgba(255,255,255,0.05)', borderRadius: [0, 6, 6, 0] },
                },
                // Faturamento sobre o trilho (gradiente na cor do polo)
                {
                    ...baseSerie,
                    name: 'Faturamento', type: 'bar', barGap: '-100%', z: 2,
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
                            label: fonteValor ? { color: cor } : undefined,
                        };
                    }),
                    label: fonteValor ? {
                        show: true, position: 'right', distance: 10,
                        fontSize: fonteValor, fontWeight: 800,
                        formatter: (p) => fmtCompacto(p.value),
                    } : { show: false },
                },
            ],
            animationEasing: 'cubicOut',
            animationDuration: 600,
        };
    }, [ord, corDoPolo, modoAtivo, parede, fonteCategoria, fonteEixo, fonteValor, interativo]);

    // Na parede a altura vem MEDIDA de fora (a faixa é fixa e todos os polos cabem nela);
    // na tela ela cresce com a lista e a caixa rola.
    const alturaChart = altura ?? Math.max(260, ord.length * ALTURA_LINHA);

    const origem = origemFaturamento(fonteFaturamento, parcial);

    return (
        <div className={cn(modo && 'flex h-full min-h-0 flex-col')}>
            {/* Toggle de métrica + origem — só no modo NÃO controlado (tela) */}
            {!modo && (
                <div className="mb-4 flex items-center justify-between gap-3">
                    <div className="inline-flex rounded-lg border border-white/[0.08] bg-white/[0.02] p-0.5">
                        {[
                            { key: 'faturamento', label: 'Faturamento' },
                            { key: 'cobertura',   label: 'Cobertura da meta' },
                        ].map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setModoLocal(t.key)}
                                className={cn(
                                    'rounded-md px-3 py-1 text-xs font-semibold transition-colors',
                                    modoAtivo === t.key ? 'bg-ecf-yellow text-black' : 'text-white/55 hover:text-white/80',
                                )}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                    <span className="text-white/30 text-[11px]">{origem}</span>
                </div>
            )}

            {ord.length === 0 ? (
                <div className="flex flex-1 items-center justify-center text-white/35"
                     style={{ height: altura ?? 280, fontSize: parede ? fonteCategoria : 14 }}>
                    Sem faturamento no mês selecionado
                </div>
            ) : (
                <div className={cn(altura ? 'min-h-0 flex-1' : 'max-h-[600px] overflow-y-auto pr-1')}>
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
