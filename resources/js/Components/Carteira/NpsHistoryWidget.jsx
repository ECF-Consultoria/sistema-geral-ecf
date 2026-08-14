import { LineChart, Line, Tooltip, ResponsiveContainer } from 'recharts';
import { Star } from 'lucide-react';
import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/**
 * NpsHistoryWidget — histórico mensal de avaliações NPS do profissional.
 *
 * Props:
 *   npsHistory {Array<{month: string, competencia: string|null, avg: number|null, count: number, ultima_nota: number|null}>}
 *     — série de até 12 meses (ex: [{month:"2026-05", competencia:"2026-04", avg:4.2, count:3, ultima_nota:5}, ...])
 *     `month` é o mês de COLETA (o rótulo do ponto); `competencia` é o mês
 *     AVALIADO (coleta − 1), exibido como "ref." — NPS coletado em agosto
 *     avalia julho.
 *   cargoSlug {"analista"|"estrategista"|null}
 *     — usado apenas para ajuste de label no título (score_analista / score_estrategista)
 *
 * Escala: 1-5 (score_analista ou score_estrategista — NÃO NPS 0-10).
 *
 * Comportamento:
 *   - Array vazio ou null → empty state "Sem avaliações no período."
 *   - Com dados → mini LineChart (80px altura) sem eixos + stats row (última nota / média / avaliações)
 */

// Rótulo pt-BR curto ('jul/26') a partir de uma chave 'YYYY-MM'.
const rotuloMes = (iso) => (iso
    ? new Date(iso + '-01').toLocaleDateString('pt-BR', { month: 'short', year: '2-digit' })
    : null);

// Tooltip customizado: fundo escuro ecf-card, texto mês + avg + count.
//
// 2026-08-14 — o mês do ponto é o da COLETA (é o que casa com a lista do /nps);
// a segunda linha diz a que mês aquela nota se refere. O NPS coletado em agosto
// avalia julho — mesma régua M/M+1 do bônus.
function TooltipNps({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const d = payload[0]?.payload;
    if (!d) return null;
    const mesLabel = rotuloMes(d.month) ?? label;
    const refLabel = rotuloMes(d.competencia);
    return (
        <div className="bg-[#0f1116] border border-white/10 rounded-md px-2.5 py-1.5 text-[11px] shadow-lg">
            <div className="text-white/60 mb-0.5">{mesLabel}</div>
            {refLabel && (
                <div className="text-white/35 text-[10px] mb-0.5">ref. {refLabel}</div>
            )}
            <div className="text-white font-semibold">
                {d.avg !== null ? `${d.avg.toFixed(1)} pts` : '—'}
            </div>
            {d.count > 0 && (
                <div className="text-white/50">{d.count} aval.</div>
            )}
        </div>
    );
}

// Barra de progresso visual (avg/5 × 100%) para mostrar nível de satisfação.
function BarraProgresso({ valor, max = 5 }) {
    const pct = valor !== null ? Math.min(100, (valor / max) * 100) : 0;
    // Cor gradual: < 3 → âmbar, 3-4 → sky, > 4 → emerald
    const cor = valor === null ? 'bg-white/20'
        : valor < 3    ? 'bg-amber-400'
        : valor < 4    ? 'bg-sky-400'
        : 'bg-emerald-400';
    return (
        <div className="h-1 w-full bg-white/10 rounded-full overflow-hidden mt-1">
            <div
                className={cn('h-full rounded-full transition-all', cor)}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

export default function NpsHistoryWidget({ npsHistory = [], cargoSlug }) {
    const temDados = Array.isArray(npsHistory) && npsHistory.length > 0;

    // Prepara dados para o chart: apenas entradas com avg não-null
    const chartData = temDados
        ? npsHistory.map(d => ({
              month: d.month,
              // Mês avaliado (backend, 2026-08-14). Fallback pro mês de coleta
              // mantém o widget legível se o payload vier de versão anterior.
              competencia: d.competencia ?? null,
              avg: d.avg,
              count: d.count ?? 0,
          }))
        : [];

    // Stats: média geral, última nota, total de avaliações
    const entradasComAvg = temDados ? npsHistory.filter(d => d.avg !== null) : [];
    const mediaGeral = entradasComAvg.length > 0
        ? entradasComAvg.reduce((acc, d) => acc + d.avg, 0) / entradasComAvg.length
        : null;
    const totalAvaliacoes = temDados
        ? npsHistory.reduce((acc, d) => acc + (d.count ?? 0), 0)
        : 0;
    // Última nota: pega ultima_nota da entrada mais recente que a tenha
    const ultimaEntrada = temDados
        ? [...npsHistory].reverse().find(d => d.ultima_nota !== null && d.ultima_nota !== undefined)
        : null;
    const ultimaNota = ultimaEntrada?.ultima_nota ?? null;

    return (
        <Card className="bg-ecf-card/60 border-white/[0.06]">
            <CardContent className="p-4">
                {/* Header */}
                <div className="flex items-center gap-2 text-white/90 text-sm font-semibold mb-3">
                    <Star size={14} className="text-ecf-yellow" />
                    Histórico NPS
                    <span className="text-white/35 text-[10px] font-normal">· cada mês avalia o anterior</span>
                </div>

                {/* Empty state */}
                {!temDados && (
                    <p className="text-white/40 text-[12px]">Sem avaliações no período.</p>
                )}

                {/* Conteúdo com dados */}
                {temDados && (
                    <>
                        {/* Mini LineChart — 80px altura, sem eixos, sem grid */}
                        <div style={{ height: 80 }} className="w-full mb-3">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={chartData} margin={{ top: 4, right: 4, left: 4, bottom: 4 }}>
                                    <Line
                                        type="monotone"
                                        dataKey="avg"
                                        stroke="#ffe600"
                                        strokeWidth={2}
                                        dot={{ r: 2.5, fill: '#ffe600', strokeWidth: 0 }}
                                        activeDot={{ r: 4, fill: '#ffe600', strokeWidth: 0 }}
                                        isAnimationActive={false}
                                        connectNulls={false}
                                    />
                                    <Tooltip
                                        content={<TooltipNps />}
                                        cursor={{ stroke: 'rgba(255,255,255,0.1)', strokeWidth: 1 }}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>

                        {/* Stats row: última nota | média | avaliações */}
                        <div className="grid grid-cols-3 gap-2 text-center">
                            {/* Última nota */}
                            <div>
                                <div className="text-white/50 text-[10px] uppercase tracking-wide mb-0.5">Última</div>
                                <div className={cn(
                                    'text-lg font-bold tabular-nums',
                                    ultimaNota === null ? 'text-white/30'
                                    : ultimaNota < 3 ? 'text-amber-300'
                                    : ultimaNota < 4 ? 'text-sky-300'
                                    : 'text-emerald-300'
                                )}>
                                    {ultimaNota !== null ? ultimaNota : '—'}
                                </div>
                                {ultimaNota !== null && (
                                    <BarraProgresso valor={ultimaNota} />
                                )}
                            </div>

                            {/* Média do período */}
                            <div>
                                <div className="text-white/50 text-[10px] uppercase tracking-wide mb-0.5">Média</div>
                                <div className={cn(
                                    'text-lg font-bold tabular-nums',
                                    mediaGeral === null ? 'text-white/30'
                                    : mediaGeral < 3 ? 'text-amber-300'
                                    : mediaGeral < 4 ? 'text-sky-300'
                                    : 'text-emerald-300'
                                )}>
                                    {mediaGeral !== null ? mediaGeral.toFixed(1) : '—'}
                                </div>
                                {mediaGeral !== null && (
                                    <BarraProgresso valor={mediaGeral} />
                                )}
                            </div>

                            {/* Total de avaliações */}
                            <div>
                                <div className="text-white/50 text-[10px] uppercase tracking-wide mb-0.5">Aval.</div>
                                <div className="text-lg font-bold tabular-nums text-white/80">
                                    {totalAvaliacoes}
                                </div>
                                <div className="text-[10px] text-white/30 mt-1">total</div>
                            </div>
                        </div>

                        {/* Escala de referência */}
                        <div className="flex justify-between text-[9px] text-white/25 mt-2 px-0.5">
                            <span>1 — Ruim</span>
                            <span>escala 1-5</span>
                            <span>5 — Excelente</span>
                        </div>
                    </>
                )}
            </CardContent>
        </Card>
    );
}
